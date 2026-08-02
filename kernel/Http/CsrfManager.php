<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * CSRF token management — generate, rotate, validate, and render tokens.
 *
 * Extracted from App.php for testability and single responsibility.
 * Uses PHP sessions for token storage.
 *
 * @package Ikabud\Kernel\Http
 */
final class CsrfManager
{
    /**
     * Get or generate the current CSRF token.
     */
    public static function token(): string
    {
        self::ensureSession();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Rotate the CSRF token and optionally regenerate the session ID.
     */
    public static function rotate(bool $regenerateSessionId = false): string
    {
        self::ensureSession();
        if ($regenerateSessionId && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            @session_regenerate_id(true);
        }
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }

    /**
     * Render a hidden HTML input field containing the CSRF token.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Validate the CSRF token from the current request input or header.
     * Sends a 419 JSON response on failure.
     *
     * @param callable(array): void $sendJson Function to send JSON response (for DI)
     */
    public static function enforce(?callable $sendJson = null): void
    {
        $input = Input::get();
        $token = $input['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($token) || $token === '' || !hash_equals(self::token(), $token)) {
            $respond = $sendJson ?? static function (array $data): void {
                http_response_code(419);
                header('Content-Type: application/json');
                echo json_encode($data);
                exit;
            };
            $respond(['ok' => false, 'error' => 'Invalid CSRF token']);
        }
    }

    /**
     * Ensure a PHP session is active.
     */
    private static function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            self::ensureWritableSessionPath();
            session_start();
        }
    }

    private static function ensureWritableSessionPath(): void
    {
        if (function_exists('kernel_ensure_writable_session_path')) {
            kernel_ensure_writable_session_path();
            return;
        }

        $current = session_save_path();
        $path = $current !== '' ? $current : sys_get_temp_dir();

        if (is_dir($path) && is_writable($path)) {
            return;
        }

        $fallback = dirname(__DIR__, 2) . '/storage/sessions';
        if ((!is_dir($fallback) && !@mkdir($fallback, 0700, true)) || !is_writable($fallback)) {
            $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/ikabud-sessions';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0700, true);
            }
        }

        if (is_dir($fallback) && is_writable($fallback)) {
            session_save_path($fallback);
        }
    }
}
