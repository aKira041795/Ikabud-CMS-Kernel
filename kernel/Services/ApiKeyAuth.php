<?php
/**
 * API Key Authentication Service (Tier 3.8)
 *
 * Kernel-level API key authentication for headless/external access.
 * Keys are stored hashed (SHA-256) with a visible 8-char prefix for identification.
 * Keys support scope-based access control and per-key rate limiting.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

class ApiKeyAuth
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function tableExists(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM kernel_api_keys LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generate a new API key. Returns the raw key (shown once) and the DB record.
     */
    public function createKey(int $tenantId, string $name, array $scopes = [], array $options = []): array
    {
        $rawKey = bin2hex(random_bytes(32)); // 64 hex chars
        $prefix = substr($rawKey, 0, 8);
        $hash = hash('sha256', $rawKey);
        $rateLimit = max(1, (int)($options['rate_limit'] ?? 1000));
        $expiresAt = isset($options['expires_at']) ? (string)$options['expires_at'] : null;
        $createdBy = isset($options['created_by']) ? (int)$options['created_by'] : null;

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO kernel_api_keys
                (tenant_id, name, key_prefix, key_hash, scopes, rate_limit, expires_at, is_active, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $name,
            $prefix,
            $hash,
            json_encode($scopes),
            $rateLimit,
            $expiresAt,
            $createdBy,
            $now,
        ]);

        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'key' => $rawKey,
            'prefix' => $prefix,
            'name' => $name,
            'scopes' => $scopes,
            'rate_limit' => $rateLimit,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Authenticate a request by API key. Returns the key record or null.
     */
    public function authenticate(string $rawKey): ?array
    {
        if (strlen($rawKey) < 16) return null;

        $prefix = substr($rawKey, 0, 8);
        $hash = hash('sha256', $rawKey);

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT * FROM kernel_api_keys
             WHERE key_prefix = ? AND key_hash = ? AND is_active = 1
             AND (expires_at IS NULL OR expires_at > ?)
             LIMIT 1'
        );
        $stmt->execute([$prefix, $hash, $now]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        // Update last_used_at
        $this->db->prepare(
            'UPDATE kernel_api_keys SET last_used_at = ? WHERE id = ?'
        )->execute([$now, (int)$row['id']]);

        $row['scopes'] = json_decode((string)($row['scopes'] ?? '[]'), true) ?: [];
        return $row;
    }

    /**
     * Check if a key has a specific scope permission.
     */
    public function hasScope(array $keyRecord, string $scope): bool
    {
        $scopes = $keyRecord['scopes'] ?? [];
        if (is_string($scopes)) {
            $scopes = json_decode($scopes, true) ?: [];
        }
        if (!is_array($scopes)) {
            return false;
        }
        if (in_array('*', $scopes, true)) {
            return true;
        }
        return in_array($scope, $scopes, true);
    }

    /**
     * Revoke an API key.
     */
    public function revokeKey(int $id, int $tenantId): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE kernel_api_keys SET is_active = 0, updated_at = ? WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$now, $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List active keys for a tenant (never returns the hash).
     */
    public function listKeys(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, key_prefix, scopes, rate_limit, last_used_at, expires_at, is_active, created_at
             FROM kernel_api_keys WHERE tenant_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['scopes'] = json_decode((string)($row['scopes'] ?? '[]'), true) ?: [];
        }
        return $rows;
    }

    /**
     * Delete an API key permanently.
     */
    public function deleteKey(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM kernel_api_keys WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Extract API key from request headers or query string.
     */
    public static function extractKeyFromRequest(): ?string
    {
        // Check Authorization: Bearer header
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }

        // Check X-API-Key header
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($apiKey !== '') {
            return trim($apiKey);
        }

        // Check query parameter (not recommended for production)
        $qsKey = $_GET['api_key'] ?? '';
        if ($qsKey !== '') {
            return trim($qsKey);
        }

        return null;
    }
}
