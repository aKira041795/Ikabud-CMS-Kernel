<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * HTTP Input service — parses request body (JSON, form data, query string)
 * and sanitizes all values (null byte stripping, depth limits).
 *
 * Extracted from App.php for testability and single responsibility.
 *
 * @package Ikabud\Kernel\Http
 */
final class Input
{
    /** Max input payload size in bytes (2 MB). */
    public const MAX_INPUT_SIZE = 2_097_152;

    /** @var array|null Cached parsed input */
    private static ?array $input = null;

    /** @var string|null Cache invalidation signature for CLI mode */
    private static ?string $inputSignature = null;

    /**
     * Get a parsed input value, or the full input array.
     *
     * Reads php://input for PUT/PATCH/DELETE requests.
     * Parses JSON body when Content-Type is application/json.
     * Merges GET + POST for standard form submissions.
     * Automatically caches the result for the lifetime of the request.
     * In CLI mode, recaches when the request signature changes (for testing).
     */
    public static function get(?string $key = null, mixed $default = null): mixed
    {
        $currentSignature = null;
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $currentSignature = md5(serialize([
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['CONTENT_TYPE'] ?? '',
                $_GET,
                $_POST,
            ]));
        }

        if (self::$input === null || ($currentSignature !== null && self::$inputSignature !== $currentSignature)) {
            self::$input = self::parse();
            self::$inputSignature = $currentSignature;
        }

        if ($key === null) {
            return self::$input;
        }

        return self::$input[$key] ?? $default;
    }

    /**
     * Parse the current request input into an array.
     */
    private static function parse(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === false || strlen($raw) > self::MAX_INPUT_SIZE) {
                return [];
            }
            $decoded = json_decode($raw, true, 32);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                return ['_json_error' => json_last_error_msg()];
            }
            $input = $decoded ?? [];
        } elseif (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['PUT', 'PATCH', 'DELETE'], true)) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && strlen($raw) <= self::MAX_INPUT_SIZE) {
                parse_str($raw, $parsed);
                $input = array_merge($_GET, $parsed);
            } else {
                $input = $_GET;
            }
        } else {
            $input = array_merge($_GET, $_POST);
        }

        return self::sanitize($input);
    }

    /**
     * Recursively sanitize input array: strip null bytes, enforce depth limit.
     */
    public static function sanitize(mixed $data, int $depth = 0): mixed
    {
        if ($depth > 32) {
            return null;
        }
        if (is_string($data)) {
            return str_replace("\0", '', $data);
        }
        if (is_array($data)) {
            $clean = [];
            foreach ($data as $k => $v) {
                $cleanKey = is_string($k) ? str_replace("\0", '', $k) : $k;
                $clean[$cleanKey] = self::sanitize($v, $depth + 1);
            }
            return $clean;
        }
        return $data;
    }

    /**
     * Clear the cached input (useful for testing between requests in CLI mode).
     */
    public static function reset(): void
    {
        self::$input = null;
        self::$inputSignature = null;
    }
}
