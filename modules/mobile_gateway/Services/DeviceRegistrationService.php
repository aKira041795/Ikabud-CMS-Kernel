<?php

declare(strict_types=1);

/**
 * Device registration and management service.
 *
 * Handles device identity persistence (mgw_devices table) and coordinates
 * with kernel-level PushNotification::registerToken() for FCM token storage.
 * Also associates devices with kernel_device_sessions for session tracking.
 */

class MobileDeviceRegistrationService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? (function_exists('app') ? app()->db() : throw new RuntimeException('No database available'));
    }

    /**
     * Register or update a device.
     *
     * @param int         $userId    Authenticated user ID
     * @param int         $tenantId  Tenant ID from the verified Bearer token
     * @param string      $deviceId  Client-generated unique device identifier
     * @param string      $platform  Device platform ('android', 'ios', 'web')
     * @param string|null $pushToken FCM/APNs push token (nullable)
     * @param string|null $deviceName Human-readable device name
     * @param string|null $ip        Client IP address
     * @param string|null $userAgent User agent string
     * @param int|null    $sessionId Associated kernel_device_sessions.id
     * @return array{device_id: string, status: string}
     */
    public function register(
        int $userId,
        int $tenantId,
        string $deviceId,
        string $platform,
        ?string $pushToken = null,
        ?string $deviceName = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?int $sessionId = null
    ): array {
        if ($userId <= 0 || $tenantId <= 0) {
            throw new InvalidArgumentException('Tenant and user context are required');
        }

        if ($sessionId === null) {
            $stmt = $this->db->prepare(
                'SELECT id FROM kernel_device_sessions
                 WHERE user_id = ? AND tenant_id = ? AND device_id = ? AND revoked_at IS NULL
                 ORDER BY last_seen_at DESC, id DESC LIMIT 1'
            );
            $stmt->execute([$userId, $tenantId, $deviceId]);
            $resolvedSessionId = $stmt->fetchColumn();
            $sessionId = $resolvedSessionId !== false ? (int)$resolvedSessionId : null;
        }

        $previousTokenStmt = $this->db->prepare(
            'SELECT push_token FROM mgw_devices
             WHERE user_id = ? AND tenant_id = ? AND device_id = ? LIMIT 1'
        );
        $previousTokenStmt->execute([$userId, $tenantId, $deviceId]);
        $previousPushToken = $previousTokenStmt->fetchColumn();

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
            'INSERT INTO mgw_devices
             (user_id, tenant_id, device_id, device_name, platform, push_token, status,
              device_session_id, last_ip, last_user_agent, last_seen_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
             platform = VALUES(platform),
             push_token = COALESCE(VALUES(push_token), push_token),
             device_name = COALESCE(VALUES(device_name), device_name),
             device_session_id = COALESCE(VALUES(device_session_id), device_session_id),
             last_ip = VALUES(last_ip),
             last_user_agent = VALUES(last_user_agent),
             last_seen_at = NOW(),
             status = \'active\',
             updated_at = NOW()'
            )->execute([
                $userId,
                $tenantId,
                $deviceId,
                $deviceName,
                $platform,
                $pushToken,
                $sessionId,
                $ip,
                $userAgent,
            ]);

            if ($pushToken !== null && $pushToken !== '') {
                \Ikabud\Kernel\Services\PushNotification::registerToken(
                    $tenantId,
                    $userId,
                    $pushToken,
                    $platform
                );
                if (
                    is_string($previousPushToken)
                    && $previousPushToken !== ''
                    && !hash_equals($previousPushToken, $pushToken)
                ) {
                    \Ikabud\Kernel\Services\PushNotification::unregisterToken($previousPushToken);
                }
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'device_id' => $deviceId,
            'status' => 'active',
        ];
    }

    /**
     * Unregister/revoke a device.
     *
     * @param int    $userId   Authenticated user ID
     * @param string $deviceId Device identifier to revoke
     * @return array{device_id: string, status: string}
     */
    public function unregister(
        int $userId,
        int $tenantId,
        string $deviceId
    ): array
    {
        $stmt = $this->db->prepare(
            'SELECT push_token FROM mgw_devices
             WHERE user_id = ? AND tenant_id = ? AND device_id = ? AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([$userId, $tenantId, $deviceId]);
        $pushToken = $stmt->fetchColumn();
        if ($pushToken === false) {
            return ['device_id' => $deviceId, 'status' => 'not_found'];
        }

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'UPDATE mgw_devices SET status = \'revoked\', updated_at = NOW()
                 WHERE user_id = ? AND tenant_id = ? AND device_id = ? AND status = \'active\''
            )->execute([$userId, $tenantId, $deviceId]);

            if (is_string($pushToken) && $pushToken !== '') {
                \Ikabud\Kernel\Services\PushNotification::unregisterToken($pushToken);
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'device_id' => $deviceId,
            'status' => 'revoked',
        ];
    }

    /**
     * Get all devices for a user.
     *
     * @param int $userId
     * @param int $tenantId
     * @return array<int, array>
     */
    public function getDevicesForUser(int $userId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mgw_devices
             WHERE user_id = ? AND tenant_id = ? AND status = \'active\''
        );
        $stmt->execute([$userId, $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Revoke all devices for a user (e.g., on password change).
     *
     * @param int $userId
     * @param int $tenantId
     */
    public function revokeAllForUser(int $userId, int $tenantId): void
    {
        $tokenStmt = $this->db->prepare(
            'SELECT push_token FROM mgw_devices
             WHERE user_id = ? AND tenant_id = ? AND status = \'active\' AND push_token IS NOT NULL'
        );
        $tokenStmt->execute([$userId, $tenantId]);
        $tokens = $tokenStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'UPDATE mgw_devices SET status = \'revoked\', updated_at = NOW()
                 WHERE user_id = ? AND tenant_id = ? AND status = \'active\''
            )->execute([$userId, $tenantId]);

            foreach ($tokens as $token) {
                if (is_string($token) && $token !== '') {
                    \Ikabud\Kernel\Services\PushNotification::unregisterToken($token);
                }
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
