<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

class Crypto
{
    private string $key;
    private string $currentKeyId;
    /** @var array<string, string> key_id => raw_key */
    private array $keyRing = [];

    public function __construct(?string $key = null)
    {
        if ($key === null || $key === '') {
            $cfgKey = config('app.crypto.control_db_enc_key', null);
            if (is_string($cfgKey) && $cfgKey !== '') {
                $key = $cfgKey;
            }
        }
        $key = $key ?? (string)($_ENV['CONTROL_DB_ENC_KEY'] ?? $_ENV['APP_ENCRYPTION_KEY'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('Missing encryption key. Set CONTROL_DB_ENC_KEY or APP_ENCRYPTION_KEY.');
        }

        $raw = base64_decode($key, true);
        if ($raw !== false) {
            $key = $raw;
        }

        if (strlen($key) < 32) {
            throw new \RuntimeException('Encryption key must be at least 32 bytes (or base64-encoded 32+ bytes).');
        }

        $this->key = $key;
        $this->currentKeyId = 'default';
        $this->keyRing['default'] = $key;

        // Load additional keys from key ring config if available
        $this->loadKeyRing();
    }

    /**
     * Load additional keys from environment for key rotation support.
     * Format: APP_ENCRYPTION_KEY_<ID>=<base64-encoded-key>
     */
    private function loadKeyRing(): void
    {
        foreach ($_ENV as $envKey => $envValue) {
            if (preg_match('/^APP_ENCRYPTION_KEY_(\w+)$/i', $envKey, $m)) {
                $keyId = strtolower($m[1]);
                if ($keyId === 'default' || $keyId === '') continue;
                $decoded = base64_decode((string)$envValue, true);
                $rawKey = $decoded !== false ? $decoded : (string)$envValue;
                if (strlen($rawKey) >= 32) {
                    $this->keyRing[$keyId] = $rawKey;
                }
            }
        }

        // If a current key ID is specified, switch to it
        $activeKeyId = trim((string)($_ENV['APP_ENCRYPTION_ACTIVE_KEY'] ?? ''));
        if ($activeKeyId !== '' && isset($this->keyRing[$activeKeyId])) {
            $this->currentKeyId = $activeKeyId;
            $this->key = $this->keyRing[$activeKeyId];
        }
    }

    /**
     * Encrypt a string using AES-256-GCM.
     * Returns array with base64 encoded fields: ciphertext, iv, tag, key_id.
     */
    public function encryptString(string $plaintext): array
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || $tag === '') {
            throw new \RuntimeException('Encryption failed.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'key_id' => $this->currentKeyId,
        ];
    }

    public function decryptString(string $ciphertextB64, string $ivB64, string $tagB64, ?string $keyId = null): string
    {
        $ciphertext = base64_decode($ciphertextB64, true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new \RuntimeException('Invalid encrypted payload (base64 decode failed).');
        }

        // Resolve key from key ring
        $decryptKey = $this->key;
        if ($keyId !== null && isset($this->keyRing[$keyId])) {
            $decryptKey = $this->keyRing[$keyId];
        } elseif ($keyId !== null && !isset($this->keyRing[$keyId])) {
            // Try decryption with default key — may have been encrypted before rotation
            $decryptKey = $this->keyRing['default'] ?? $this->key;
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $decryptKey, OPENSSL_RAW_DATA, $iv, $tag);

        // If decryption failed and we have a key ring, try all keys (fallback for missing key_id)
        if ($plaintext === false && count($this->keyRing) > 1) {
            foreach ($this->keyRing as $id => $ringKey) {
                $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $ringKey, OPENSSL_RAW_DATA, $iv, $tag);
                if ($plaintext !== false) break;
            }
        }

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Re-encrypt data from any key in the ring using the current active key.
     * Returns new envelope or null if data was already encrypted with the current key.
     */
    public function reEncrypt(string $ciphertextB64, string $ivB64, string $tagB64, ?string $keyId = null): ?array
    {
        if ($keyId === $this->currentKeyId) {
            return null; // Already on current key
        }

        $plaintext = $this->decryptString($ciphertextB64, $ivB64, $tagB64, $keyId);
        return $this->encryptString($plaintext);
    }

    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    public function keyRingIds(): array
    {
        return array_keys($this->keyRing);
    }

    public function hasKeyId(string $keyId): bool
    {
        return isset($this->keyRing[$keyId]);
    }
}
