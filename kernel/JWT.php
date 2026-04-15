<?php
namespace Ikabud\Kernel;

/**
 * JWT (JSON Web Token) Handler
 * 
 * Standalone JWT implementation for the Ikabud Kernel System.
 * Supports HS256 signing, token refresh, and token version validation
 * for invalidation on password change or account deactivation.
 */
class JWT
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    private string $issuer;
    
    public function __construct(?string $secret = null, int $expiration = 86400, string $algorithm = 'HS256', string $issuer = 'ikabud')
    {
        $this->secret = $secret ?? ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '');
        if (empty($this->secret) || strlen($this->secret) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters. Add a strong JWT_SECRET to your .env file.');
        }
        $this->algorithm = $algorithm;
        $this->expiration = $expiration;
        $this->issuer = $issuer;
    }
    
    /**
     * Generate JWT token
     */
    public function generate(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
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
        
        // Verify signature
        $expectedSignature = $this->sign($headerEncoded . '.' . $payloadEncoded);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return null;
        }

        // Validate algorithm matches what this instance expects to prevent
        // algorithm confusion attacks (e.g. switching HS256 ↔ RS256).
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== $this->algorithm) {
            return null;
        }
        
        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        if (!$payload) {
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
     * Sign data
     */
    private function sign(string $data): string
    {
        $signature = hash_hmac('sha256', $data, $this->secret, true);
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
