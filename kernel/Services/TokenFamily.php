<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * Token family — refresh token rotation with reuse detection.
 *
 * Each device/session gets a unique token family (family_id).
 * Within a family, refresh tokens are single-use: each rotation
 * issues a new token and marks the old one as consumed.
 *
 * If a previously-consumed refresh token is presented again,
 * the entire family is revoked (potential token theft detected).
 */
class TokenFamily
{
    /**
     * Attempt to rotate a refresh token within its family.
     *
     * @param string $familyId   Unique family identifier (UUID)
     * @param string $tokenHash  SHA-256 hash of the presented refresh token
     * @return array{success: bool, user_id?: int, new_token?: string, new_hash?: string, expires_at?: string}
     *
     * On theft detection, returns success=false with reason='theft_detected'
     * and the entire family is revoked.
     */
    public static function rotate(string $familyId, string $tokenHash): array
    {
        $db = self::db();

        try {
            // Lock the family row for atomicity
            $stmt = $db->prepare(
                'SELECT id, user_id, status, current_token_hash, consumed_token_hashes
                 FROM kernel_token_families
                 WHERE family_id = ? FOR UPDATE'
            );
            $stmt->execute([$familyId]);
            $family = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$family) {
                return ['success' => false, 'reason' => 'family_not_found'];
            }

            $consumed = [];
            if (!empty($family['consumed_token_hashes'])) {
                $consumed = json_decode($family['consumed_token_hashes'], true) ?? [];
            }

            // Check if this token was already consumed (theft detection)
            if (in_array($tokenHash, $consumed, true)) {
                // Token theft detected — revoke the entire family
                $db->prepare(
                    'UPDATE kernel_token_families
                     SET status = \'revoked\', revoked_at = NOW()
                     WHERE family_id = ?'
                )->execute([$familyId]);

                // Also revoke all associated device sessions
                if (function_exists('app')) {
                    $db->prepare(
                        'UPDATE kernel_device_sessions
                         SET revoked_at = NOW()
                         WHERE token_family_id = ? AND revoked_at IS NULL'
                    )->execute([$familyId]);
                }

                self::log('token_theft_detected', 'critical', [
                    'family_id' => $familyId,
                    'user_id' => $family['user_id'],
                ]);

                return ['success' => false, 'reason' => 'theft_detected'];
            }

            // Mark current token as consumed
            $consumed[] = $family['current_token_hash'];

            // Generate new refresh token
            $newRefreshToken = bin2hex(random_bytes(32));
            $newHash = hash('sha256', $newRefreshToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

            $db->prepare(
                'UPDATE kernel_token_families
                 SET current_token_hash = ?,
                     consumed_token_hashes = ?,
                     updated_at = NOW()
                 WHERE family_id = ?'
            )->execute([$newHash, json_encode($consumed), $familyId]);

            return [
                'success'    => true,
                'user_id'    => (int)$family['user_id'],
                'new_token'  => $newRefreshToken,
                'new_hash'   => $newHash,
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            self::log('token_family_rotate_error', 'error', [
                'family_id' => $familyId,
                'error'     => $e->getMessage(),
            ]);
            return ['success' => false, 'reason' => 'internal_error'];
        }
    }

    /**
     * Create a new token family for a device/session.
     *
     * @param int    $userId    User ID
     * @param string $deviceId  Unique device identifier
     * @return array{family_id: string, refresh_token: string, refresh_hash: string, expires_at: string}
     */
    public static function create(int $userId, string $deviceId): array
    {
        $familyId = bin2hex(random_bytes(16));
        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        self::db()->prepare(
            'INSERT INTO kernel_token_families (family_id, user_id, current_token_hash, status, created_at, updated_at)
             VALUES (?, ?, ?, \'active\', NOW(), NOW())'
        )->execute([$familyId, $userId, $refreshHash]);

        return [
            'family_id'     => $familyId,
            'refresh_token' => $refreshToken,
            'refresh_hash'  => $refreshHash,
            'expires_at'    => $expiresAt,
        ];
    }

    /**
     * Revoke an entire token family.
     */
    public static function revoke(string $familyId): void
    {
        self::db()->prepare(
            'UPDATE kernel_token_families
             SET status = \'revoked\', revoked_at = NOW()
             WHERE family_id = ? AND status = \'active\''
        )->execute([$familyId]);
    }

    /**
     * Revoke all families for a user (logout all devices).
     */
    public static function revokeAllForUser(int $userId): void
    {
        self::db()->prepare(
            'UPDATE kernel_token_families
             SET status = \'revoked\', revoked_at = NOW()
             WHERE user_id = ? AND status = \'active\''
        )->execute([$userId]);

        self::db()->prepare(
            'UPDATE kernel_device_sessions
             SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL'
        )->execute([$userId]);
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
