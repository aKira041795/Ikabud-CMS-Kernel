<?php
namespace Ikabud\Kernel;

/**
 * JWT (JSON Web Token) Handler
 * 
 * Standalone JWT implementation for the Ikabud Kernel System.
 * Supports HS256 signing, token refresh, token version validation
 * for invalidation on password change or account deactivation,
 * and key rotation via JWT_SECRET_<ID> environment variables.
 */
final class JWT
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    private string $issuer;
    /** @var array<string, string> key_id => raw_key for rotation support */
    private array $keyRing = [];
    private string $activeKeyId = 'default';
    
    public function __construct(?string $secret = null, int $expiration = 86400, string $algorithm = 'HS256', string $issuer = 'ikabud')
    {
        $this->secret = $secret ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        if (empty($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters. Add a strong JWT_SECRET to your .env file.');
        }
        $this->algorithm = $algorithm;
        $this->expiration = $expiration;
        $this->issuer = $issuer;

        // Build key ring for rotation support.
        // Primary key: JWT_SECRET. Additional keys: JWT_SECRET_<ID>.
        $this->keyRing['default'] = $this->secret;
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
     * Generate JWT token
     */
    public function generate(array $payload): string
    {
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
            $expectedSignature = $this->signWithKey($headerEncoded . '.' . $payloadEncoded, $this->keyRing[$kid]);
            if (hash_equals($signature, $expectedSignature)) {
                $signatureVerified = true;
            }
        }

        // Fall back: try all keys in the ring (handles key rotation transition)
        if (!$signatureVerified) {
            foreach ($this->keyRing as $ringKeyId => $ringKey) {
                if ($ringKeyId === $kid) continue; // already tried
                $expectedSignature = $this->signWithKey($headerEncoded . '.' . $payloadEncoded, $ringKey);
                if (hash_equals($signature, $expectedSignature)) {
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
     * Extract token from Authorization header
     */
    public static function extractFromHeader(): ?string
    {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            
            if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Sign data with the active key
     */
    private function sign(string $data): string
    {
        return $this->signWithKey($data, $this->secret);
    }

    /**
     * Sign data with a specific key from the ring
     */
    private function signWithKey(string $data, string $key): string
    {
        $signature = hash_hmac('sha256', $data, $key, true);
        return $this->base64UrlEncode($signature);
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
