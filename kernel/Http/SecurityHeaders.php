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

final class SecurityHeaders
{
    /** @var array Static file extensions to skip */
    private const STATIC_EXTENSIONS = [
        '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg',
        '.woff', '.woff2', '.ttf', '.eot', '.ico', '.map', '.json'
    ];

    /**
     * External origins permitted to serve images. Narrowed from a blanket
     * `https:` so a content-injection vector cannot beacon to arbitrary hosts.
     * Extend this list deliberately if CMS content must load images from
     * additional origins (e.g. an external CDN/media host).
     */
    private const IMG_SRC_ORIGINS = [
        'https://cdn.tailwindcss.com',
        'https://unpkg.com',
        'https://cdn.jsdelivr.net',
        'https://cdnjs.cloudflare.com',
        'https://maps.googleapis.com',
        'https://maps.gstatic.com',
        'https://fonts.gstatic.com',
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
     * Apply security headers to the response
     * 
     * @return bool True if headers were applied, false if skipped
     */
    public function apply(): bool
    {
        // F24: Activate PHP session security settings before any session_start().
        $this->applyPHPSettings();

        $headers = $this->headers();
        if ($headers === []) {
            return false;
        }

        foreach ($headers as $headerValue) {
            header($headerValue);
        }

        return true;
    }

    /**
     * Build the response security headers for the current request.
     * Exposed so header policy can be tested without relying on SAPI header state.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        if ($this->isStaticAsset) {
            return [];
        }

        $headers = [
            'X-Frame-Options: SAMEORIGIN',
            'X-Content-Type-Options: nosniff',
            'Content-Security-Policy: ' . $this->buildCspHeaderValue(),
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Permissions-Policy: geolocation=self, camera=self, microphone=(), payment=(), usb=()',
        ];

        if ($this->isHttps()) {
            $headers[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        }

        return $headers;
    }
    
    /**
     * Apply Content Security Policy header
     * 
     * Allows CDN resources used by the app (Tailwind, HTMX, Alpine, Font Awesome)
     */
    private function buildCspHeaderValue(): string
    {
        // NOTE: Do NOT add the nonce to script-src while 'unsafe-inline' is present.
        // Per CSP Level 2/3, a nonce in script-src overrides 'unsafe-inline', so any
        // inline <script> without the matching nonce attribute is blocked by modern browsers.
        // When templates are updated to carry nonce="..." on every inline script, remove
        // 'unsafe-inline' and re-add the nonce here.
        //
        // 'unsafe-eval' is required by:
        //   - Alpine.js v3 (CDN build uses new Function() for expression evaluation)
        //   - Tailwind CSS CDN (JIT mode generates styles via eval-based class scanning)
        $scriptSrc = ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://cdn.tailwindcss.com', 'https://unpkg.com', 'https://cdn.jsdelivr.net', 'https://maps.googleapis.com'];      

        // CSP nonce transition: when CSP_NONCE_MODE is enabled, replace
        // 'unsafe-inline' with the per-request nonce. Templates must carry
        // nonce="{csp_nonce}" on every inline <script> tag before enabling.
        if (function_exists('csp_nonce_mode_enabled') && csp_nonce_mode_enabled()
            && function_exists('csp_nonce') && csp_nonce() !== '') {
            $scriptSrc = array_values(array_filter($scriptSrc, fn($s) => $s !== "'unsafe-inline'"));
            $scriptSrc[] = "'nonce-" . csp_nonce() . "'";
        }

        $csp = implode('; ', [
            "default-src 'self'",
            'script-src ' . implode(' ', $scriptSrc),
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.tailwindcss.com https://fonts.googleapis.com",
            "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "img-src 'self' data: blob: " . implode(' ', self::IMG_SRC_ORIGINS),
            "connect-src 'self' https://unpkg.com https://maps.googleapis.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "worker-src 'self' blob:",
        ]);

        return $csp;
    }
    
    /**
     * Apply PHP security settings for sessions
     */
    public function applyPHPSettings(): void
    {
        // Only effective before session_start(); skip silently if session is already active.
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

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
