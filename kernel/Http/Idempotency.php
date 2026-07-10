<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Idempotency key support for safe mobile retries.
 *
 * Idempotency keys prevent duplicate writes when network interruptions cause
 * clients to retry POST/PUT requests. The client sends an Idempotency-Key header;
 * if the server has already processed that key, it returns the stored response
 * instead of applying the mutation again.
 *
 * Usage in handlers:
 *   $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
 *   if ($key) {
 *       $existing = Idempotency::check($key, $tenantId);
 *       if ($existing) {
 *           ApiResponse::success($existing, 200);
 *           return;
 *       }
 *   }
 *   // ... process mutation ...
 *   if ($key) {
 *       Idempotency::store($key, $tenantId, ['id' => $newId]);
 *   }
 *   ApiResponse::success(['id' => $newId], 201);
 */
class Idempotency
{
    /**
     * Check if an idempotency key has already been processed.
     * Returns the stored response if found, null if this is a new request.
     */
    public static function check(string $key, int $tenantId): ?array
    {
        $hash = hash('sha256', $key);

        $stmt = self::db()->prepare(
            'SELECT response_json FROM kernel_idempotency_keys
             WHERE idempotency_key_hash = ? AND tenant_id = ? AND status = \'completed\''
        );
        $stmt->execute([$hash, $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row && !empty($row['response_json'])) {
            return json_decode($row['response_json'], true);
        }

        // Mark as processing to prevent concurrent duplicate processing
        self::db()->prepare(
            'INSERT INTO kernel_idempotency_keys (idempotency_key_hash, tenant_id, status, created_at)
             VALUES (?, ?, \'processing\', NOW())
             ON DUPLICATE KEY UPDATE status = IF(status = \'completed\', status, \'processing\')'
        )->execute([$hash, $tenantId]);

        return null;
    }

    /**
     * Store the response after successful processing.
     */
    public static function store(string $key, int $tenantId, array $response): void
    {
        $hash = hash('sha256', $key);

        self::db()->prepare(
            'UPDATE kernel_idempotency_keys
             SET status = \'completed\', response_json = ?
             WHERE idempotency_key_hash = ? AND tenant_id = ?'
        )->execute([json_encode($response), $hash, $tenantId]);
    }

    /**
     * Release the key after a processing failure, so the client can retry.
     */
    public static function release(string $key, int $tenantId): void
    {
        $hash = hash('sha256', $key);

        self::db()->prepare(
            'DELETE FROM kernel_idempotency_keys
             WHERE idempotency_key_hash = ? AND tenant_id = ? AND status = \'processing\''
        )->execute([$hash, $tenantId]);
    }

    /**
     * Get the kernel database connection.
     */
    private static function db(): \PDO
    {
        if (function_exists('app') && $app = \app()) {
            return $app->db();
        }
        throw new \RuntimeException('Application not available for database access');
    }
}
