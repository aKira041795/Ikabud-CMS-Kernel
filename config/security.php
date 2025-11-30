<?php
/**
 * DiSyL Security Configuration
 * 
 * Security settings for template signing, rate limiting, and CSP.
 * 
 * @package IkabudKernel
 * @version 0.6.0
 */

return [
    /**
     * Template Signing
     */
    'template_signing' => [
        /**
         * Enable template signing
         */
        'enabled' => true,
        
        /**
         * Secret key for HMAC signing
         * IMPORTANT: Change this in production!
         * You can also set DISYL_SIGNING_KEY environment variable
         */
        'signing_key' => getenv('DISYL_SIGNING_KEY') ?: null,
        
        /**
         * Enforce signatures (reject unsigned templates)
         * Set to true in production for maximum security
         */
        'enforce_signatures' => false,
        
        /**
         * Hash algorithm
         */
        'algorithm' => 'sha256',
    ],
    
    /**
     * Rate Limiting
     */
    'rate_limiting' => [
        /**
         * Enable rate limiting
         */
        'enabled' => true,
        
        /**
         * Maximum queries per time window
         */
        'max_queries' => 60,
        
        /**
         * Time window in seconds
         */
        'window_seconds' => 60,
        
        /**
         * Maximum results per query
         */
        'max_results_per_query' => 100,
        
        /**
         * Storage path for file-based rate limiting
         * (Used when APCu is not available)
         */
        'storage_path' => dirname(__DIR__) . '/storage/rate-limits',
    ],
    
    /**
     * Content Security Policy
     */
    'csp' => [
        /**
         * Enable CSP header generation
         */
        'enabled' => true,
        
        /**
         * Report-only mode (doesn't block, just reports)
         */
        'report_only' => false,
        
        /**
         * Report URI for CSP violations
         */
        'report_uri' => null,
        
        /**
         * Trusted domains for external resources
         */
        'trusted_domains' => [
            'fonts.googleapis.com',
            'fonts.gstatic.com',
            'cdnjs.cloudflare.com',
            'cdn.jsdelivr.net',
            'unpkg.com',
        ],
        
        /**
         * Default CSP directives
         */
        'default_directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'", 'https://fonts.gstatic.com'],
            'connect-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
        ],
    ],
    
    /**
     * Instance Authorization
     */
    'authorization' => [
        /**
         * Enable instance authorization
         */
        'enabled' => true,
        
        /**
         * Path to permissions config file
         */
        'permissions_file' => dirname(__DIR__) . '/config/cross-instance-permissions.php',
    ],
];
