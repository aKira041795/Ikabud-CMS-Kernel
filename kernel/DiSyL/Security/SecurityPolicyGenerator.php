<?php
/**
 * DiSyL Security Policy Generator
 * 
 * Generates Content Security Policy (CSP) headers based on template content.
 * Helps prevent XSS and other injection attacks.
 * 
 * @package IkabudKernel\Core\DiSyL\Security
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL\Security;

class SecurityPolicyGenerator
{
    /** @var array Default CSP directives */
    private static array $defaultDirectives = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'https:'],
        'font-src' => ["'self'", 'https://fonts.gstatic.com'],
        'connect-src' => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
    ];
    
    /** @var array Trusted domains for external resources */
    private static array $trustedDomains = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'cdnjs.cloudflare.com',
        'cdn.jsdelivr.net',
    ];
    
    /** @var bool Whether to use report-only mode */
    private static bool $reportOnly = false;
    
    /** @var string|null Report URI for CSP violations */
    private static ?string $reportUri = null;
    
    /** @var string Nonce for inline scripts (generated per request) */
    private static ?string $nonce = null;
    
    /**
     * Initialize the generator
     * 
     * @param array $options Configuration options
     */
    public static function init(array $options = []): void
    {
        if (isset($options['trusted_domains'])) {
            self::$trustedDomains = array_merge(self::$trustedDomains, $options['trusted_domains']);
        }
        if (isset($options['report_only'])) {
            self::$reportOnly = (bool)$options['report_only'];
        }
        if (isset($options['report_uri'])) {
            self::$reportUri = $options['report_uri'];
        }
        if (isset($options['default_directives'])) {
            self::$defaultDirectives = array_merge(self::$defaultDirectives, $options['default_directives']);
        }
        
        // Generate nonce for this request
        self::$nonce = self::generateNonce();
    }
    
    /**
     * Generate a cryptographic nonce
     * 
     * @return string
     */
    public static function generateNonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }
    
    /**
     * Get the current nonce
     * 
     * @return string
     */
    public static function getNonce(): string
    {
        return self::$nonce ?? self::generateNonce();
    }
    
    /**
     * Generate CSP header from template content
     * 
     * @param string $templateContent DiSyL template content
     * @param array $additionalSources Additional sources to allow
     * @return string CSP header value
     */
    public static function generate(string $templateContent, array $additionalSources = []): string
    {
        $directives = self::$defaultDirectives;
        
        // Analyze template for external resources
        $analysis = self::analyzeTemplate($templateContent);
        
        // Add detected sources
        if (!empty($analysis['images'])) {
            $directives['img-src'] = array_merge(
                $directives['img-src'] ?? [],
                $analysis['images']
            );
        }
        
        if (!empty($analysis['scripts'])) {
            $directives['script-src'] = array_merge(
                $directives['script-src'] ?? [],
                $analysis['scripts']
            );
        }
        
        if (!empty($analysis['styles'])) {
            $directives['style-src'] = array_merge(
                $directives['style-src'] ?? [],
                $analysis['styles']
            );
        }
        
        if (!empty($analysis['fonts'])) {
            $directives['font-src'] = array_merge(
                $directives['font-src'] ?? [],
                $analysis['fonts']
            );
        }
        
        if (!empty($analysis['frames'])) {
            $directives['frame-src'] = array_merge(
                $directives['frame-src'] ?? [],
                $analysis['frames']
            );
        }
        
        // Add nonce for inline scripts
        $nonce = self::getNonce();
        $directives['script-src'][] = "'nonce-{$nonce}'";
        
        // Add additional sources
        foreach ($additionalSources as $directive => $sources) {
            if (!isset($directives[$directive])) {
                $directives[$directive] = [];
            }
            $directives[$directive] = array_merge($directives[$directive], (array)$sources);
        }
        
        // Add report URI if configured
        if (self::$reportUri) {
            $directives['report-uri'] = [self::$reportUri];
        }
        
        // Build CSP string
        return self::buildCspString($directives);
    }
    
    /**
     * Analyze template for external resources
     * 
     * @param string $content Template content
     * @return array Detected sources by type
     */
    private static function analyzeTemplate(string $content): array
    {
        $analysis = [
            'images' => [],
            'scripts' => [],
            'styles' => [],
            'fonts' => [],
            'frames' => [],
        ];
        
        // Detect image sources
        if (preg_match_all('/src=["\']?(https?:\/\/[^"\'>\s]+)/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host && self::isTrustedDomain($host)) {
                    $analysis['images'][] = "https://{$host}";
                }
            }
        }
        
        // Detect ikb_image sources
        if (preg_match_all('/\{ikb_image[^}]*src=["\']?([^"\'}\s]+)/i', $content, $matches)) {
            foreach ($matches[1] as $src) {
                if (preg_match('/^https?:\/\//', $src)) {
                    $host = parse_url($src, PHP_URL_HOST);
                    if ($host && self::isTrustedDomain($host)) {
                        $analysis['images'][] = "https://{$host}";
                    }
                }
            }
        }
        
        // Detect stylesheet links
        if (preg_match_all('/href=["\']?(https?:\/\/[^"\'>\s]+\.css)/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host && self::isTrustedDomain($host)) {
                    $analysis['styles'][] = "https://{$host}";
                }
            }
        }
        
        // Detect Google Fonts
        if (stripos($content, 'fonts.googleapis.com') !== false) {
            $analysis['styles'][] = 'https://fonts.googleapis.com';
            $analysis['fonts'][] = 'https://fonts.gstatic.com';
        }
        
        // Detect iframe sources
        if (preg_match_all('/<iframe[^>]*src=["\']?(https?:\/\/[^"\'>\s]+)/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                if ($host) {
                    // Only allow specific trusted iframe sources
                    $trustedFrameSources = ['youtube.com', 'www.youtube.com', 'vimeo.com', 'player.vimeo.com'];
                    if (in_array($host, $trustedFrameSources)) {
                        $analysis['frames'][] = "https://{$host}";
                    }
                }
            }
        }
        
        // Remove duplicates
        foreach ($analysis as $key => $sources) {
            $analysis[$key] = array_unique($sources);
        }
        
        return $analysis;
    }
    
    /**
     * Check if a domain is trusted
     * 
     * @param string $domain
     * @return bool
     */
    private static function isTrustedDomain(string $domain): bool
    {
        foreach (self::$trustedDomains as $trusted) {
            if ($domain === $trusted || str_ends_with($domain, '.' . $trusted)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Build CSP header string from directives
     * 
     * @param array $directives
     * @return string
     */
    private static function buildCspString(array $directives): string
    {
        $parts = [];
        
        foreach ($directives as $directive => $sources) {
            if (!empty($sources)) {
                $uniqueSources = array_unique($sources);
                $parts[] = $directive . ' ' . implode(' ', $uniqueSources);
            }
        }
        
        return implode('; ', $parts);
    }
    
    /**
     * Send CSP header
     * 
     * @param string $csp CSP header value
     */
    public static function sendHeader(string $csp): void
    {
        if (headers_sent()) {
            return;
        }
        
        $headerName = self::$reportOnly 
            ? 'Content-Security-Policy-Report-Only' 
            : 'Content-Security-Policy';
        
        header("{$headerName}: {$csp}");
    }
    
    /**
     * Generate and send CSP header for template
     * 
     * @param string $templateContent
     * @param array $additionalSources
     */
    public static function apply(string $templateContent, array $additionalSources = []): void
    {
        $csp = self::generate($templateContent, $additionalSources);
        self::sendHeader($csp);
    }
    
    /**
     * Add trusted domain
     * 
     * @param string $domain
     */
    public static function addTrustedDomain(string $domain): void
    {
        if (!in_array($domain, self::$trustedDomains)) {
            self::$trustedDomains[] = $domain;
        }
    }
    
    /**
     * Set report-only mode
     * 
     * @param bool $reportOnly
     */
    public static function setReportOnly(bool $reportOnly): void
    {
        self::$reportOnly = $reportOnly;
    }
    
    /**
     * Set report URI
     * 
     * @param string $uri
     */
    public static function setReportUri(string $uri): void
    {
        self::$reportUri = $uri;
    }
    
    /**
     * Get meta tag for CSP (alternative to header)
     * 
     * @param string $csp
     * @return string HTML meta tag
     */
    public static function getMetaTag(string $csp): string
    {
        // Note: report-uri doesn't work in meta tags
        $csp = preg_replace('/;\s*report-uri[^;]*/', '', $csp);
        return '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp) . '">';
    }
    
    /**
     * Generate strict CSP for production
     * 
     * @return string
     */
    public static function generateStrict(): string
    {
        return self::buildCspString([
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-" . self::getNonce() . "'"],
            'style-src' => ["'self'", "'nonce-" . self::getNonce() . "'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'upgrade-insecure-requests' => [],
        ]);
    }
}
