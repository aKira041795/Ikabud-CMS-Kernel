<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Push notification service — queues notifications for FCM/APNs delivery.
 *
 * Notifications are queued in kernel_push_queue and delivered by PushWorker
 * (cron or background job). This keeps web request times fast and allows
 * retry with exponential backoff.
 *
 * Usage:
 *   PushNotification::dispatch($tenantId, $userId, 'Order shipped', 'Your order #42 has shipped.', ['order_id' => 42]);
 *   PushNotification::registerToken($tenantId, $userId, $fcmToken, 'android');
 *   PushNotification::unregisterToken($token);
 */
class PushNotification
{
    /**
     * Queue a push notification for delivery.
     * Does NOT send immediately — enqueues for the worker.
     */
    public static function dispatch(
        int $tenantId,
        int $userId,
        string $title,
        string $body,
        array $data = [],
        int $maxAttempts = 5
    ): void {
        self::db()->prepare(
            'INSERT INTO kernel_push_queue (tenant_id, user_id, title, body, data_json, max_attempts)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $userId, $title, $body,
            !empty($data) ? json_encode($data) : null,
            $maxAttempts,
        ]);
    }

    /**
     * Send push notification to a specific user across all their registered devices.
     * This is a convenience wrapper that queues one notification per device token.
     */
    public static function sendToUser(int $tenantId, int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = self::db()->prepare(
            'SELECT token, platform FROM kernel_push_tokens
             WHERE tenant_id = ? AND user_id = ? AND is_valid = TRUE'
        );
        $tokens->execute([$tenantId, $userId]);

        foreach ($tokens->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            self::dispatch($tenantId, $userId, $title, $body, [
                'push_token' => $row['token'],
                'platform'   => $row['platform'],
                '_data'      => $data,
            ]);
        }
    }

    /**
     * Register a device token for push notifications.
     */
    public static function registerToken(int $tenantId, int $userId, string $token, string $platform = 'android'): void
    {
        self::db()->prepare(
            'INSERT INTO kernel_push_tokens (tenant_id, user_id, token, platform)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 user_id = VALUES(user_id),
                 is_valid = TRUE,
                 invalidated_at = NULL,
                 updated_at = NOW()'
        )->execute([$tenantId, $userId, $token, $platform]);
    }

    /**
     * Unregister (invalidate) a device token.
     */
    public static function unregisterToken(string $token): void
    {
        self::db()->prepare(
            'UPDATE kernel_push_tokens
             SET is_valid = FALSE, invalidated_at = NOW()
             WHERE token = ?'
        )->execute([$token]);
    }

    /**
     * Mark a device token as invalid (e.g. FCM returned NotRegistered).
     */
    public static function invalidateToken(string $token): void
    {
        self::unregisterToken($token);
        self::log('push_token_invalidated', 'warning', [
            'token_prefix' => substr($token, 0, 20) . '...',
        ]);
    }

    private static function db(): \PDO
    {
        if (function_exists('app') && $app = \app()) {
            return $app->db();
        }
        throw new \RuntimeException('Application not available');
    }

    private static function log(string $message, string $level, array $context = []): void
    {
        if (function_exists('write_log')) {
            write_log($message, $level, $context);
        }
    }
}
