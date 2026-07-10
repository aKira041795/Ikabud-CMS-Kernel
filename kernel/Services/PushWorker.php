<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Push notification delivery worker — processes pending notifications
 * from kernel_push_queue and sends them via FCM HTTP v1 API.
 *
 * Designed to be called from a cron job or kernel job queue.
 *
 * Usage (cron: every minute):
 *   PushWorker::processBatch(10);
 *
 * Usage (single notification):
 *   PushWorker::processOne($queueId);
 */
class PushWorker
{
    /**
     * Process a batch of pending push notifications.
     *
     * @param int $batchSize Max items to process in this batch
     * @return int Number of successfully sent notifications
     */
    public static function processBatch(int $batchSize = 10): int
    {
        $db = self::db();
        $sent = 0;

        $stmt = $db->prepare(
            'SELECT id, tenant_id, user_id, title, body, data_json, attempts, max_attempts
             FROM kernel_push_queue
             WHERE status = \'pending\' AND available_at <= NOW()
             ORDER BY created_at ASC
             LIMIT ? FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([$batchSize]);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result = self::sendFcm($row);

            if ($result['success']) {
                $db->prepare(
                    'UPDATE kernel_push_queue
                     SET status = \'sent\', completed_at = NOW()
                     WHERE id = ?'
                )->execute([$row['id']]);
                $sent++;
            } else {
                $newAttempts = (int)$row['attempts'] + 1;
                $maxAttempts = (int)$row['max_attempts'];

                if ($newAttempts >= $maxAttempts) {
                    $db->prepare(
                        'UPDATE kernel_push_queue
                         SET status = \'dead\', attempts = ?, last_error = ?, completed_at = NOW()
                         WHERE id = ?'
                    )->execute([$newAttempts, $result['error'], $row['id']]);

                    self::log('push_delivery_failed_max_attempts', 'error', [
                        'queue_id' => $row['id'],
                        'error'    => $result['error'],
                    ]);
                } else {
                    // Exponential backoff: 30s, 120s, 480s, 1920s, ...
                    $backoff = 30 * pow(4, $newAttempts - 1);
                    $availableAt = date('Y-m-d H:i:s', time() + $backoff);

                    $db->prepare(
                        'UPDATE kernel_push_queue
                         SET status = \'pending\', attempts = ?, last_error = ?,
                             available_at = ?
                         WHERE id = ?'
                    )->execute([$newAttempts, $result['error'], $availableAt, $row['id']]);
                }
            }
        }

        return $sent;
    }

    /**
     * Process a single push notification by queue ID.
     */
    public static function processOne(int $queueId): bool
    {
        $db = self::db();

        $stmt = $db->prepare(
            'SELECT id, tenant_id, user_id, title, body, data_json, attempts, max_attempts
             FROM kernel_push_queue
             WHERE id = ? AND status = \'pending\''
        );
        $stmt->execute([$queueId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $result = self::sendFcm($row);

        if ($result['success']) {
            $db->prepare(
                'UPDATE kernel_push_queue SET status = \'sent\', completed_at = NOW() WHERE id = ?'
            )->execute([$queueId]);
            return true;
        }

        // Mark as dead on failure for single-item processing
        $db->prepare(
            'UPDATE kernel_push_queue SET status = \'failed\', last_error = ?, completed_at = NOW() WHERE id = ?'
        )->execute([$result['error'], $queueId]);

        return false;
    }

    /**
     * Send via FCM HTTP v1 API.
     * Requires FCM_SERVER_KEY environment variable.
     *
     * @return array{success: bool, error?: string}
     */
    private static function sendFcm(array $row): array
    {
        $fcmKey = $_ENV['FCM_SERVER_KEY'] ?? '';

        if ($fcmKey === '') {
            return ['success' => false, 'error' => 'FCM_SERVER_KEY not configured'];
        }

        $data = $row['data_json'] ? json_decode($row['data_json'], true) : [];
        $pushToken = $data['push_token'] ?? '';

        if (empty($pushToken)) {
            return ['success' => false, 'error' => 'No push token in data'];
        }

        // Build FCM HTTP v1 message
        $message = [
            'message' => [
                'token'        => $pushToken,
                'notification' => [
                    'title' => $row['title'],
                    'body'  => $row['body'],
                ],
                'data' => $data['_data'] ?? [],
            ],
        ];

        $json = json_encode($message);
        if ($json === false) {
            return ['success' => false, 'error' => 'JSON encoding failed'];
        }

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: key=' . $fcmKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return ['success' => false, 'error' => 'CURL: ' . $curlError];
        }

        $responseData = json_decode($response, true);

        // FCM legacy HTTP API response codes
        if ($httpCode === 200 && isset($responseData['success']) && $responseData['success'] > 0) {
            return ['success' => true];
        }

        // Check for invalid token
        if (isset($responseData['results'][0]['error'])) {
            $fcmError = $responseData['results'][0]['error'];
            if (in_array($fcmError, ['NotRegistered', 'InvalidRegistration'], true)) {
                PushNotification::invalidateToken($pushToken);
                return ['success' => false, 'error' => "FCM: {$fcmError} (token invalidated)"];
            }
            return ['success' => false, 'error' => "FCM: {$fcmError}"];
        }

        return ['success' => false, 'error' => "FCM HTTP {$httpCode}"];
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
