<?php
namespace IkabudKernel\Core;

/**
 * JWT (JSON Web Token) Handler
 * 
 * Simple JWT implementation for authentication
 */
class JWT
{
    private string $secret;
    private string $algorithm;
    private int $expiration;
    
    public function __construct()
    {
        $this->secret = Config::get('JWT_SECRET', 'ikabud-kernel-secret-change-this');
        $this->algorithm = Config::get('JWT_ALGORITHM', 'HS256');
        $this->expiration = (int) Config::get('JWT_EXPIRATION', 86400); // 24 hours
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
        
        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiration;
        
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        
        $signature = $this->sign($headerEncoded . '.' . $payloadEncoded);
        
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signature;
    }
    
    /**
     * Verify and decode JWT token
     */
    public function verify(string $token): ?array
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
        
        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        
        if (!$payload) {
            return null;
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
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
        
        // Remove old timestamps
        unset($payload['iat'], $payload['exp']);
        
        // Generate new token
        return $this->generate($payload);
    }
}
