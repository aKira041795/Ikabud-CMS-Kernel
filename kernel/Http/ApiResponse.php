<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Standardized API response envelope.
 *
 * All methods call app()->json() which sets Content-Type, echoes JSON, and exits.
 *
 * Response contracts:
 *   Success:  {"ok":true, "data":{...}, "meta":{}, "request_id":"..."}
 *   Error:    {"ok":false, "error":{"code":"not_found","message":"Not found","details":null}, "request_id":"..."}
 *   Validation: {"ok":false, "error":{"code":"validation_failed","message":"Validation failed","details":{"fields":{}}}, "request_id":"..."}
 *   Paginated: {"ok":true, "data":[...], "meta":{"pagination":{...},"links":{...}}, "request_id":"..."}
 */
class ApiResponse
{
    /**
     * Success response — always includes "data" (nullable) and "meta" (always present).
     */
    public static function success(mixed $data = null, int $status = 200, array $meta = []): void
    {
        $app = self::app();
        $body = ['ok' => true, 'data' => $data, 'meta' => $meta];
        $app->json($body, $status);
    }

    /**
     * Error response — nested error object for extensibility.
     *
     * @param string $code    Machine-readable error code (e.g. 'not_found', 'unauthorized')
     * @param string $message Human-readable error message
     * @param int    $status  HTTP status code
     * @param mixed  $details Optional structured details (e.g. validation field errors)
     */
    public static function error(string $code, string $message, int $status = 400, mixed $details = null): void
    {
        $app = self::app();
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        $app->json(['ok' => false, 'error' => $error], $status);
    }

    /**
     * Validation error — emits 422 with per-field messages in error.details.fields.
     *
     * @param array  $fieldErrors Map of field name => error message
     * @param string $message     Optional override message
     */
    public static function validationError(array $fieldErrors, string $message = 'Validation failed'): void
    {
        self::error('validation_failed', $message, 422, ['fields' => $fieldErrors]);
    }

    /**
     * Paginated success response.
     *
     * @param array     $items     List of items for this page
     * @param Paginator $paginator Paginator instance with meta and links
     */
    public static function paginated(array $items, Paginator $paginator): void
    {
        self::success($items, 200, [
            'pagination' => $paginator->meta(),
            'links'      => $paginator->links(),
        ]);
    }

    /**
     * Cursor-paginated success response.
     *
     * @param array           $items     List of items for this page
     * @param CursorPaginator $paginator CursorPaginator instance with cursor meta
     */
    public static function cursorPaginated(array $items, CursorPaginator $paginator): void
    {
        self::success($items, 200, [
            'cursor' => $paginator->meta(),
        ]);
    }

    /**
     * @return \Ikabud\Kernel\App
     */
    private static function app()
    {
        $app = \app();
        if (!$app) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Internal server error']);
            exit;
        }
        return $app;
    }
}
