<?php

declare(strict_types=1);

namespace Ikabud\Kernel;

class Crypto
{
    private string $key;

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
    }

    /**
     * Encrypt a string using AES-256-GCM.
     * Returns array with base64 encoded fields: ciphertext, iv, tag.
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
        ];
    }

    public function decryptString(string $ciphertextB64, string $ivB64, string $tagB64): string
    {
        $ciphertext = base64_decode($ciphertextB64, true);
        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        if ($ciphertext === false || $iv === false || $tag === false) {
            throw new \RuntimeException('Invalid encrypted payload (base64 decode failed).');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }
}
