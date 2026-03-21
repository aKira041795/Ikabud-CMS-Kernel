<?php
/**
 * Security Headers Manager
 * 
 * Applies HTTP security headers for the Ikabud Kernel System.
 * Handles CSP, X-Frame-Options, HSTS, and PHP session hardening.
 * 
 * @package Ikabud\Kernel\Http
 * @version 2.0.0
 */

namespace Ikabud\Kernel\Http;

class SecurityHeaders
{
    /** @var array Static file extensions to skip */
    private const STATIC_EXTENSIONS = [
        '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg',
        '.woff', '.woff2', '.ttf', '.eot', '.ico', '.map', '.json'
    ];
    
    /** @var string|null Current request URI */
    private ?string $requestUri;
    
    /** @var string|null Current host */
    private ?string $currentHost;
    
    /** @var bool Whether this is a static asset request */
    private bool $isStaticAsset = false;
    
    public function __construct(?string $requestUri = null, ?string $currentHost = null)
    {
        $this->requestUri = $requestUri ?? ($_SERVER['REQUEST_URI'] ?? '');
        $this->currentHost = $currentHost ?? ($_SERVER['HTTP_HOST'] ?? '');
        
        $this->detectStaticAsset();
    }
    
    /**
     * Detect if request is for a static asset
     */
    private function detectStaticAsset(): void
    {
        $uriPath = strtolower(parse_url($this->requestUri, PHP_URL_PATH) ?? '');
        
        foreach (self::STATIC_EXTENSIONS as $ext) {
            if (str_ends_with($uriPath, $ext)) {
                $this->isStaticAsset = true;
                return;
            }
        }
        
        // Asset directories
        if (str_contains($uriPath, '/assets/')) {
            $this->isStaticAsset = true;
        }
    }
    
    /**
     * Get base domain for frame-ancestors
     */
    private function getBaseDomain(): string
    {
        $hostParts = explode('.', $this->currentHost);
        return implode('.', array_slice($hostParts, -2));
    }
    
    /**
     * Apply security headers to the response
     * 
     * @return bool True if headers were applied, false if skipped
     */
    public function apply(): bool
    {
        // Skip for static assets (served directly by Apache)
        if ($this->isStaticAsset) {
            return false;
        }
        
        // Clickjacking protection
        header('X-Frame-Options: SAMEORIGIN');
        
        // MIME type sniffing protection
        header('X-Content-Type-Options: nosniff');
        
        // XSS filter (legacy browsers)
        header('X-XSS-Protection: 1; mode=block');
        
        // Content Security Policy
        $this->applyCSP();
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions policy — disable unused browser features
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        
        // HSTS when on HTTPS
        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        
        return true;
    }
    
    /**
     * Apply Content Security Policy header
     * 
     * Allows CDN resources used by the app (Tailwind, HTMX, Alpine, Font Awesome)
     */
    private function applyCSP(): void
    {
        $baseDomain = $this->getBaseDomain();
        
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.tailwindcss.com",
            "font-src 'self' data: https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "worker-src 'self' blob:",
        ]);
        
        header("Content-Security-Policy: " . $csp);
    }
    
    /**
     * Apply PHP security settings for sessions
     */
    public function applyPHPSettings(): void
    {
        ini_set('session.cookie_httponly', '1');
        
        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
            ini_set('session.cookie_samesite', 'Strict');
        } else {
            ini_set('session.cookie_secure', '0');
            ini_set('session.cookie_samesite', 'Lax');
        }
    }
    
    /**
     * Check if current connection is HTTPS
     */
    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }
    
    /**
     * Check if current request is for a static asset
     */
    public function isStaticAssetRequest(): bool
    {
        return $this->isStaticAsset;
    }
}
