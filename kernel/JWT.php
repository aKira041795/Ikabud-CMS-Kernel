<?php
namespace Ikabud\Kernel;

/**
 * JWT (JSON Web Token) Handler
 * 
 * Standalone JWT implementation for the Ikabud Kernel System.
 * Supports HS256 signing, token refresh, token version validation
 * for invalidation on password change or account deactivation,
 * and key rotation via JWT_SECRET_<ID> environment variables.
 * 
 * Since 2026-08-05 also supports RS256 (asymmetric) signing so distributed
 * API/mobile clients can verify tokens with a public key instead of sharing
 * the symmetric secret:
 *   - JWT_ALG=RS256, JWT_PRIVATE_KEY / JWT_PRIVATE_KEY_PATH (signing),
 *   - JWT_PUBLIC_KEY / JWT_PUBLIC_KEY_PATH (+ JWT_PUBLIC_KEY_<ID> rotation).
 */
final class JWT
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    private string $issuer;
    /** @var array<string, string> key_id => key material (HS256 shared secret | RS256 public PEM) */
    private array $keyRing = [];
    private string $activeKeyId = 'default';
    /** @var string|null RS256 private key PEM (signing only). */
    private ?string $privateKey = null;
    
    public function __construct(?string $secret = null, int $expiration = 86400, string $algorithm = 'HS256', string $issuer = 'ikabud')
    {
        $this->algorithm = strtoupper((string)$algorithm);
        $this->expiration = $expiration;
        $this->issuer = $issuer;

        if (!in_array($this->algorithm, ['HS256', 'RS256'], true)) {
            throw new \RuntimeException("Unsupported JWT algorithm '{$this->algorithm}'. Supported: HS256, RS256.");
        }

        if ($this->algorithm === 'RS256') {
            $this->loadRsaKeyMaterial();
            return;
        }

        // ── HS256: shared-secret path (default) ──
        $this->secret = $secret ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        if (empty($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters. Add a strong JWT_SECRET to your .env file.');
        }
        $this->keyRing['default'] = $this->secret;

        // Build key ring for rotation support.
        // Primary key: JWT_SECRET. Additional keys: JWT_SECRET_<ID>.
        foreach (($_ENV + getenv()) as $envKey => $envValue) {
            if (!is_string($envKey) || !is_string($envValue)) continue;
            if (preg_match('/^JWT_SECRET_(\w+)$/i', $envKey, $m)) {
                $keyId = strtolower($m[1]);
                if ($keyId === 'default' || $keyId === '') continue;
                if (strlen($envValue) >= 32) {
                    $this->keyRing[$keyId] = $envValue;
                }
            }
        }

        // If an active key ID is specified, use it for signing.
        $activeKeyId = trim((string)($_ENV['JWT_SECRET_ACTIVE_KEY'] ?? ''));
        if ($activeKeyId !== '' && isset($this->keyRing[$activeKeyId])) {
            $this->activeKeyId = $activeKeyId;
            $this->secret = $this->keyRing[$activeKeyId];
        }
    }

    /**
     * Load RS256 key material.
     *
     * Signing requires a private key (JWT_PRIVATE_KEY PEM or
     * JWT_PRIVATE_KEY_PATH). Verification requires a public-key ring:
     * JWT_PUBLIC_KEY / JWT_PUBLIC_KEY_PATH plus optional
     * JWT_PUBLIC_KEY_<ID> entries for rotation. If no public key is
     * configured, it is derived from the private key.
     */
    private function loadRsaKeyMaterial(): void
    {
        if (!function_exists('openssl_sign')) {
            throw new \RuntimeException('RS256 JWT requires the OpenSSL PHP extension.');
        }

        // Private key (signing) — optional so verify-only instances work.
        $privatePem = trim((string)($_ENV['JWT_PRIVATE_KEY'] ?? ''));
        if ($privatePem === '') {
            $privatePath = trim((string)($_ENV['JWT_PRIVATE_KEY_PATH'] ?? ''));
            if ($privatePath !== '' && is_file($privatePath)) {
                $privatePem = (string)file_get_contents($privatePath);
            }
        }
        if ($privatePem !== '') {
            if (openssl_pkey_get_private($privatePem) === false) {
                throw new \RuntimeException('JWT_PRIVATE_KEY is not a valid RSA private key (PEM).');
            }
            $this->privateKey = $privatePem;
        }

        // Public-key ring (verification)
        $pubDefault = trim((string)($_ENV['JWT_PUBLIC_KEY'] ?? ''));
        if ($pubDefault === '') {
            $pubPath = trim((string)($_ENV['JWT_PUBLIC_KEY_PATH'] ?? ''));
            if ($pubPath !== '' && is_file($pubPath)) {
                $pubDefault = (string)file_get_contents($pubPath);
            }
        }
        if ($pubDefault !== '') {
            $this->keyRing['default'] = $pubDefault;
        }
        foreach (($_ENV + getenv()) as $envKey => $envValue) {
            if (!is_string($envKey) || !is_string($envValue)) continue;
            if (preg_match('/^JWT_PUBLIC_KEY_(\w+)$/i', $envKey, $m)) {
                $keyId = strtolower($m[1]);
                if ($keyId === 'default' || $keyId === '') continue;
                if (is_string($envValue) && $envValue !== '') {
                    $this->keyRing[$keyId] = $envValue;
                }
            }
        }

        // Derive the public key from the private key when no public key was
        // configured explicitly.
        if ($this->keyRing === [] && $this->privateKey !== null) {
            $res = openssl_pkey_get_private($this->privateKey);
            $details = is_resource($res) || $res instanceof \OpenSSLAsymmetricKey ? openssl_pkey_get_details($res) : false;
            if (is_array($details) && isset($details['key'])) {
                $this->keyRing['default'] = $details['key'];
            }
        }
        if ($this->keyRing === []) {
            throw new \RuntimeException('RS256 requires JWT_PUBLIC_KEY/JWT_PUBLIC_KEY_PATH (or a JWT_PRIVATE_KEY to derive from).');
        }

        $activeKeyId = trim((string)($_ENV['JWT_PUBLIC_KEY_ACTIVE_KEY'] ?? ''));
        if ($activeKeyId !== '' && isset($this->keyRing[$activeKeyId])) {
            $this->activeKeyId = $activeKeyId;
        }
    }
    
    /**
     * Generate JWT token
     */
    public function generate(array $payload): string
    {
        if ($this->algorithm === 'RS256' && $this->privateKey === null) {
            throw new \RuntimeException('Cannot sign an RS256 token: no JWT_PRIVATE_KEY configured.');
        }

        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm,
            'kid' => $this->activeKeyId,  // key ID for rotation support
        ];
        
        $now = time();
        $payload['iss'] = $this->issuer;
        $payload['iat'] = $now;
        $payload['nbf'] = $now;           // not valid before now
        $payload['exp'] = $now + $this->expiration;
        $payload['jti'] = bin2hex(random_bytes(16)); // unique token ID
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = $this->sign($headerEncoded . '.' . $payloadEncoded);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signature;
    }
    
    /**
     * Verify and decode JWT token
     * 
     * @param string $token The JWT token string
     * @param int|null $expectedTokenVersion If provided, reject tokens with a different token_version claim.
     *                                       Used to invalidate tokens after password change or account deactivation.
     * @return array|null Decoded payload or null if invalid/expired
     */
    public function verify(string $token, ?int $expectedTokenVersion = null): ?array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return null;
        }
        
        list($headerEncoded, $payloadEncoded, $signature) = $parts;

        // Validate algorithm matches what this instance expects to prevent
        // algorithm confusion attacks (e.g. switching HS256 ↔ RS256).
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== $this->algorithm) {
            return null;
        }
        
        // Decode payload early so we can check key_id for rotation support
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        // Verify signature — try the key indicated in the header's kid,
        // then fall back through the full key ring for rotation compatibility.
        $signatureVerified = false;
        $kid = isset($header['kid']) ? (string)$header['kid'] : 'default';

        // Try the key matching the token's kid first
        if (isset($this->keyRing[$kid])) {
            if ($this->verifySignature($headerEncoded . '.' . $payloadEncoded, $signature, $this->keyRing[$kid])) {
                $signatureVerified = true;
            }
        }

        // Fall back: try all keys in the ring (handles key rotation transition)
        if (!$signatureVerified) {
            foreach ($this->keyRing as $ringKeyId => $ringKey) {
                if ($ringKeyId === $kid) continue; // already tried
                if ($this->verifySignature($headerEncoded . '.' . $payloadEncoded, $signature, $ringKey)) {
                    $signatureVerified = true;
                    break;
                }
            }
        }

        if (!$signatureVerified) {
            return null;
        }
        
        $now = time();

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            return null;
        }

        // Check not-before
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return null;
        }

        // Check issuer
        if (isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
            return null;
        }
        
        // Check token version (for invalidation after password change / deactivation)
        if ($expectedTokenVersion !== null && isset($payload['token_version'])) {
            if ((int) $payload['token_version'] !== $expectedTokenVersion) {
                return null;
            }
        }
        
        return $payload;
    }
    
    /**
     * Extract token from Authorization header.
     * Works under both Apache (getallheaders) and FastCGI/FPM (no
     * getallheaders without the Apache compatibility module) by falling back
     * to the HTTP_AUTHORIZATION / REDIRECT_HTTP_AUTHORIZATION server vars.
     */
    public static function extractFromHeader(): ?string
    {
        $authorization = null;

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp((string)$k, 'Authorization') === 0) {
                        $authorization = (string)$v;
                        break;
                    }
                }
            }
        }

        if ($authorization === null || $authorization === '') {
            $authorization = (string)($_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '');
        }

        if ($authorization !== '' && preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
            return $matches[1];
        }

        return null;
    }
    
    /**
     * Sign data with the active key
     */
    private function sign(string $data): string
    {
        if ($this->algorithm === 'RS256') {
            if ($this->privateKey === null) {
                throw new \RuntimeException('Cannot sign: no RS256 private key configured.');
            }
            $signature = '';
            openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
            return $this->base64UrlEncode($signature);
        }
        return $this->signWithKey($data, $this->secret);
    }

    /**
     * Sign data with a specific shared key from the ring (HS256 only).
     */
    private function signWithKey(string $data, string $key): string
    {
        $signature = hash_hmac('sha256', $data, $key, true);
        return $this->base64UrlEncode($signature);
    }

    /**
     * Verify $data against $signatureB64url using $key, algorithm-aware.
     * HS256 → timing-safe HMAC comparison; RS256 → openssl_verify.
     */
    private function verifySignature(string $data, string $signatureB64url, string $key): bool
    {
        if ($this->algorithm === 'RS256') {
            $signature = $this->base64UrlDecode($signatureB64url);
            $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);
            return $result === 1;
        }
        $expected = $this->signWithKey($data, $key);
        return hash_equals($signatureB64url, $expected);
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Refresh token (extend expiration)
     */
    public function refresh(string $token): ?string
    {
        $payload = $this->verify($token);
        
        if (!$payload) {
            return null;
        }
        
        // Remove old timestamps and ID (new ones will be generated)
        unset($payload['iat'], $payload['exp'], $payload['nbf'], $payload['jti']);
        
        // Generate new token
        return $this->generate($payload);
    }
}
